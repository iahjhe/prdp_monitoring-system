<?php
require_once 'config/database.php';

class DashboardQueries {
    private $conn;
    private $dbType;
    
    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
        $this->dbType = $this->conn->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
    
    public function getSummaryStats() {
        try {
            $query = "
                SELECT 
                    COALESCE(SUM(f.allotment), 0) as total_allotment,
                    COALESCE(SUM(f.obligated), 0) as total_obligated,
                    COALESCE(SUM(f.disbursed), 0) as total_disbursed,
                    COALESCE(SUM(f.balance), 0) as total_balance,
                    COUNT(DISTINCT f.id) as total_funds,
                    (SELECT COUNT(*) FROM transactions) as total_transactions,
                    (SELECT COUNT(*) FROM transactions WHERE status = 'UNPAID') as pending_transactions
                FROM funds f
                WHERE f.status = 'active'
            ";
            return $this->conn->query($query)->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Dashboard Summary Stats Error: " . $e->getMessage());
            return [
                'total_allotment' => 0,
                'total_obligated' => 0,
                'total_disbursed' => 0,
                'total_balance' => 0,
                'total_funds' => 0,
                'total_transactions' => 0,
                'pending_transactions' => 0
            ];
        }
    }
    
    public function getYearlyComparison() {
        try {
            if ($this->dbType === 'mysql') {
                $query = "
                    SELECT 
                        y.year,
                        COALESCE(SUM(f.allotment), 0) as total_allotment,
                        COALESCE(SUM(f.obligated), 0) as total_obligated,
                        COALESCE(SUM(f.disbursed), 0) as total_disbursed
                    FROM years y
                    LEFT JOIN funds f ON y.id = f.year_id
                    WHERE y.year IN (2025, 2026)
                    GROUP BY y.year
                    ORDER BY y.year
                ";
            } else {
                $query = "
                    SELECT 
                        y.year,
                        COALESCE(SUM(f.allotment), 0) as total_allotment,
                        COALESCE(SUM(f.obligated), 0) as total_obligated,
                        COALESCE(SUM(f.disbursed), 0) as total_disbursed
                    FROM years y
                    LEFT JOIN funds f ON y.id = f.year_id
                    WHERE y.year IN (2025, 2026)
                    GROUP BY y.year
                    ORDER BY y.year
                ";
            }
            
            return $this->conn->query($query)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Dashboard Yearly Comparison Error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getComponentBreakdown() {
        try {
            $query = "
                SELECT 
                    c.component_code,
                    c.component_name,
                    CASE 
                        WHEN c.component_code = '1.1' THEN 'I-PLAN 1.1'
                        WHEN c.component_code = '1.2' THEN 'I-PLAN 1.2'
                        WHEN c.component_code = '2.1' THEN 'I-BUILD 2.1'
                        WHEN c.component_code = '2.2' THEN 'I-BUILD 2.2'
                        WHEN c.component_code = '3.1' THEN 'I-REAP 3.1'
                        WHEN c.component_code = '3.2' THEN 'I-REAP 3.2'
                        WHEN c.component_code = '4.0' THEN 'I-SUPPORT'
                        WHEN c.component_code = 'SRE' THEN 'SRE'
                        ELSE c.component_name
                    END as display_name,
                    COALESCE(SUM(t.obligation), 0) as total_obligated,
                    COALESCE(SUM(CASE WHEN t.status = 'paid' OR t.status = 'PAID' THEN t.obligation ELSE 0 END), 0) as total_disbursed,
                    COUNT(t.id) as transaction_count
                FROM components c
                LEFT JOIN transactions t ON c.id = t.component_id
                GROUP BY c.id, c.component_code, c.component_name
                ORDER BY total_obligated DESC
            ";
            
            return $this->conn->query($query)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Dashboard Component Breakdown Error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getRecentTransactions($limit = 10) {
        try {
            $query = "
                SELECT 
                    t.id,
                    t.date_transaction,
                    t.ors_no,
                    t.payee,
                    t.obligation,
                    t.status,
                    f.fund_name,
                    f.fund_source,
                    c.component_code,
                    CASE 
                        WHEN c.component_code = '1.1' THEN 'I-PLAN 1.1'
                        WHEN c.component_code = '1.2' THEN 'I-PLAN 1.2'
                        WHEN c.component_code = '2.1' THEN 'I-BUILD 2.1'
                        WHEN c.component_code = '2.2' THEN 'I-BUILD 2.2'
                        WHEN c.component_code = '3.1' THEN 'I-REAP 3.1'
                        WHEN c.component_code = '3.2' THEN 'I-REAP 3.2'
                        WHEN c.component_code = '4.0' THEN 'I-SUPPORT'
                        WHEN c.component_code = 'SRE' THEN 'SRE'
                        ELSE c.component_name
                    END as component_display
                FROM transactions t
                LEFT JOIN funds f ON t.fund_id = f.id
                LEFT JOIN components c ON t.component_id = c.id
                ORDER BY t.date_transaction DESC, t.id DESC
                LIMIT :limit
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Dashboard Recent Transactions Error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getFundSourceDistribution() {
        try {
            $query = "
                SELECT 
                    f.fund_source,
                    COUNT(DISTINCT f.id) as fund_count,
                    COALESCE(SUM(f.allotment), 0) as total_allotment,
                    COALESCE(SUM(f.obligated), 0) as total_obligated,
                    COALESCE(COUNT(t.id), 0) as transaction_count
                FROM funds f
                LEFT JOIN transactions t ON f.id = t.fund_id
                GROUP BY f.fund_source
            ";
            
            return $this->conn->query($query)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Dashboard Fund Source Error: " . $e->getMessage());
            return [];
        }
    }
}

// Initialize dashboard
$dashboard = new DashboardQueries();

// Get all data
$stats = $dashboard->getSummaryStats();
$yearly_comparison = $dashboard->getYearlyComparison();
$component_breakdown = $dashboard->getComponentBreakdown();
$fund_sources = $dashboard->getFundSourceDistribution();
$recent_transactions = $dashboard->getRecentTransactions(10);

// Calculate percentages
$obligation_rate = $stats['total_allotment'] > 0 ? round(($stats['total_obligated'] / $stats['total_allotment']) * 100, 2) : 0;
$disbursement_rate = $stats['total_obligated'] > 0 ? round(($stats['total_disbursed'] / $stats['total_obligated']) * 100, 2) : 0;

// Format data for charts
$component_labels = [];
$component_values = [];
$component_colors = ['#5E60CE', '#6930C3', '#64DFDF', '#FFBE0B', '#FB5607', '#FF006E', '#4CAF50', '#FF9800'];

foreach ($component_breakdown as $comp) {
    if ($comp['total_obligated'] > 0) {
        $component_labels[] = $comp['display_name'];
        $component_values[] = floatval($comp['total_obligated']);
    }
}

// Yearly comparison data
$years = [];
$yearly_allotment = [];
$yearly_obligated = [];
$yearly_disbursed = [];

foreach ($yearly_comparison as $year_data) {
    $years[] = $year_data['year'];
    $yearly_allotment[] = floatval($year_data['total_allotment']);
    $yearly_obligated[] = floatval($year_data['total_obligated']);
    $yearly_disbursed[] = floatval($year_data['total_disbursed']);
}

// Fund source data
$source_labels = [];
$source_values = [];

foreach ($fund_sources as $source) {
    $source_labels[] = $source['fund_source'] . ' Funds';
    $source_values[] = floatval($source['total_allotment']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PRDP Fund Monitoring Dashboard</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    
    <!-- AOS (Animate on Scroll) -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
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
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            --hover-shadow: 0 15px 40px rgba(94, 96, 206, 0.15);
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        main {
            flex: 1;
        }

        /* Header Styles - Already in includes/header.php but we'll add some enhancements */
        .navbar {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%) !important;
            box-shadow: 0 4px 20px rgba(94, 96, 206, 0.3);
            padding: 15px 0;
        }

        .navbar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: white !important;
        }

        .nav-link {
            color: rgba(255,255,255,0.9) !important;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            color: white !important;
            transform: translateY(-2px);
        }

        .nav-link i {
            margin-right: 5px;
        }

        /* Dashboard Header */
        .dashboard-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            padding: 30px 0;
            margin-bottom: 30px;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(94, 96, 206, 0.3);
        }

        .dashboard-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .header-content {
            position: relative;
            z-index: 2;
        }

        .header-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .header-date {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* Stat Cards */
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(94,96,206,0.1) 0%, transparent 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 20px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            box-shadow: 0 10px 20px rgba(94, 96, 206, 0.3);
            transition: all 0.3s ease;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
            margin-bottom: 10px;
        }

        .stat-progress {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 15px 0 10px;
        }

        .stat-progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 1.5s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .stat-footer {
            color: var(--gray);
            font-size: 0.9rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Chart Cards */
        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
        }

        .chart-card:hover {
            box-shadow: var(--hover-shadow);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
        }

        .chart-title i {
            color: var(--primary);
            margin-right: 10px;
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Table Styles */
        .table-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: var(--card-shadow);
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            border-top: none;
            background: var(--light);
            color: var(--dark);
            font-weight: 600;
            font-size: 0.9rem;
            padding: 15px;
        }

        .table tbody td {
            padding: 15px;
            vertical-align: middle;
            color: var(--gray);
            border-bottom: 1px solid #e9ecef;
        }

        .table tbody tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background: rgba(94, 96, 206, 0.05);
            transform: scale(1.01);
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .badge-status {
            padding: 8px 12px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.8rem;
        }

        .badge-paid {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }

        .badge-unpaid {
            background: linear-gradient(135deg, #fd7e14, #dc3545);
            color: white;
        }

        .badge-pending {
            background: linear-gradient(135deg, #17a2b8, #6f42c1);
            color: white;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 15px 30px;
            border-radius: 15px;
            border: none;
            font-weight: 600;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 10px 20px rgba(94, 96, 206, 0.2);
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(94, 96, 206, 0.3);
            color: white;
        }

        .action-btn i {
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }

        .action-btn:hover i {
            transform: rotate(360deg);
        }

        /* Insight Card */
        .insight-card {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 20px;
            padding: 30px;
            color: white;
            margin-top: 30px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .insight-card:hover {
            transform: scale(1.02);
            box-shadow: 0 20px 40px rgba(94, 96, 206, 0.3);
        }

        .insight-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
            animation: pulse-slow 4s ease infinite;
        }

        @keyframes pulse-slow {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.2); opacity: 0.8; }
        }

        .insight-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .insight-message {
            font-size: 1.5rem;
            font-weight: 500;
            line-height: 1.4;
        }

        /* Footer Styles */
        .footer {
            background: linear-gradient(135deg, var(--dark) 0%, #1a1c2c 100%);
            color: white;
            padding: 40px 0 20px;
            margin-top: 50px;
            position: relative;
            overflow: hidden;
        }

        .footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        }

        .footer h5 {
            color: var(--accent-1);
            font-weight: 600;
            margin-bottom: 20px;
        }

        .footer-links {
            list-style: none;
            padding: 0;
        }

        .footer-links li {
            margin-bottom: 10px;
        }

        .footer-links a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--accent-1);
            transform: translateX(5px);
        }

        .footer-bottom {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
            text-align: center;
            color: rgba(255,255,255,0.5);
        }

        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .social-links a {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .social-links a:hover {
            background: var(--accent-1);
            transform: translateY(-5px);
        }

        /* Loading Animation */
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header-title {
                font-size: 1.8rem;
            }
            
            .stat-value {
                font-size: 1.8rem;
            }
            
            .quick-actions {
                flex-direction: column;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Include Header -->
    <?php include 'includes/header.php'; ?>

    <main>
        <!-- Dashboard Header (inside main content) -->
        <div class="dashboard-header animate__animated animate__fadeIn">
            <div class="container header-content">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="header-title">
                            <i class="fas fa-chart-pie me-3"></i>PRDP Fund Monitoring Dashboard
                        </h1>
                        <p class="header-date">
                            <i class="fas fa-calendar-alt me-2"></i><?php echo date('l, F d, Y'); ?>
                            <span class="mx-3">|</span>
                            <i class="fas fa-database me-2"></i><?php echo number_format($stats['total_transactions']); ?> Total Transactions
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="header-stats">
                            <span class="badge bg-light text-dark p-3">
                                <i class="fas fa-chart-line me-2 text-primary"></i>
                                Obligation Rate: <?php echo $obligation_rate; ?>%
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid px-4">
            <!-- Summary Cards -->
            <div class="row g-4">
                <div class="col-xl-3 col-md-6" data-aos="fade-up" data-aos-delay="100">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-coins"></i>
                        </div>
                        <div class="stat-label">Total Allotment</div>
                        <div class="stat-value">₱<?php echo number_format($stats['total_allotment'], 2); ?></div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: 100%; background: linear-gradient(90deg, var(--primary), var(--secondary));"></div>
                        </div>
                        <div class="stat-footer">
                            <span><i class="fas fa-layer-group me-1"></i><?php echo $stats['total_funds']; ?> Active Funds</span>
                            <span class="text-primary">100%</span>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6" data-aos="fade-up" data-aos-delay="200">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div class="stat-label">Total Obligated</div>
                        <div class="stat-value">₱<?php echo number_format($stats['total_obligated'], 2); ?></div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: <?php echo $obligation_rate; ?>%; background: linear-gradient(90deg, #f093fb, #f5576c);"></div>
                        </div>
                        <div class="stat-footer">
                            <span><i class="fas fa-percent me-1"></i><?php echo $obligation_rate; ?>% of Allotment</span>
                            <span class="text-danger"><i class="fas fa-arrow-up me-1"></i><?php echo $obligation_rate; ?>%</span>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6" data-aos="fade-up" data-aos-delay="300">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <div class="stat-label">Total Disbursed</div>
                        <div class="stat-value">₱<?php echo number_format($stats['total_disbursed'], 2); ?></div>
                        <div class="stat-progress">
                            <div class="stat-progress-bar" style="width: <?php echo $disbursement_rate; ?>%; background: linear-gradient(90deg, #4facfe, #00f2fe);"></div>
                        </div>
                        <div class="stat-footer">
                            <span><i class="fas fa-check-circle me-1"></i><?php echo $disbursement_rate; ?>% of Obligated</span>
                            <span class="text-info"><i class="fas fa-chart-line me-1"></i><?php echo number_format($stats['pending_transactions']); ?> Pending</span>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6" data-aos="fade-up" data-aos-delay="400">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-label">Remaining Balance</div>
                        <div class="stat-value">₱<?php echo number_format($stats['total_balance'], 2); ?></div>
                        <div class="stat-progress">
                            <?php $balance_percent = $stats['total_allotment'] > 0 ? ($stats['total_balance'] / $stats['total_allotment']) * 100 : 0; ?>
                            <div class="stat-progress-bar" style="width: <?php echo $balance_percent; ?>%; background: linear-gradient(90deg, #43e97b, #38f9d7);"></div>
                        </div>
                        <div class="stat-footer">
                            <span><i class="fas fa-hourglass-half me-1"></i>Available Funds</span>
                            <span class="text-success">₱<?php echo number_format($stats['total_balance'], 0); ?>K</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 1 -->
            <div class="row g-4 mt-2">
                <!-- Component Distribution Chart -->
                <div class="col-xl-6" data-aos="fade-right" data-aos-delay="100">
                    <div class="chart-card">
                        <div class="chart-header">
                            <div class="chart-title">
                                <i class="fas fa-chart-pie"></i>Component Distribution
                            </div>
                            <div class="text-muted small">
                                <i class="fas fa-info-circle me-1" data-bs-toggle="tooltip" title="Obligation distribution across components"></i>
                                Total: ₱<?php echo number_format(array_sum($component_values), 2); ?>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="componentChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Yearly Comparison Chart -->
                <div class="col-xl-6" data-aos="fade-left" data-aos-delay="200">
                    <div class="chart-card">
                        <div class="chart-header">
                            <div class="chart-title">
                                <i class="fas fa-chart-bar"></i>Yearly Comparison (2025 vs 2026)
                            </div>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary active" onclick="toggleYearlyData('allotment')">Allotment</button>
                                <button class="btn btn-outline-primary" onclick="toggleYearlyData('obligated')">Obligated</button>
                                <button class="btn btn-outline-primary" onclick="toggleYearlyData('disbursed')">Disbursed</button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="yearlyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 2 -->
            <div class="row g-4 mt-2">
                <!-- Fund Source Distribution -->
                <div class="col-xl-6" data-aos="fade-right" data-aos-delay="300">
                    <div class="chart-card">
                        <div class="chart-header">
                            <div class="chart-title">
                                <i class="fas fa-chart-donut"></i>Fund Source Distribution
                            </div>
                            <div class="text-muted small">
                                <i class="fas fa-info-circle me-1" data-bs-toggle="tooltip" title="Allotment by fund source"></i>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="fundSourceChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Component Performance Chart -->
                <div class="col-xl-6" data-aos="fade-left" data-aos-delay="400">
                    <div class="chart-card">
                        <div class="chart-header">
                            <div class="chart-title">
                                <i class="fas fa-chart-line"></i>Component Performance
                            </div>
                            <div class="text-muted small">
                                <i class="fas fa-info-circle me-1" data-bs-toggle="tooltip" title="Obligated vs Disbursed by component"></i>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="performanceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Transactions Table -->
            <div class="row mt-4" data-aos="fade-up" data-aos-delay="500">
                <div class="col-12">
                    <div class="table-card">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="mb-0">
                                <i class="fas fa-history me-2 text-primary"></i>Recent Transactions
                            </h5>
                            <a href="transactions/index.php" class="btn btn-sm btn-outline-primary">
                                View All <i class="fas fa-arrow-right ms-2"></i>
                            </a>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table" id="recentTransactions">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>ORS No.</th>
                                        <th>Fund</th>
                                        <th>Component</th>
                                        <th>Payee</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recent_transactions as $trans): ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-calendar-alt me-2 text-muted"></i>
                                            <?php echo date('M d, Y', strtotime($trans['date_transaction'])); ?>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($trans['ors_no'] ?? 'N/A'); ?></code></td>
                                        <td>
                                            <span class="badge bg-light text-dark">
                                                <?php echo htmlspecialchars($trans['fund_source'] ?? 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($trans['component_display'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($trans['payee'] ?? 'N/A'); ?></td>
                                        <td class="fw-bold">₱<?php echo number_format($trans['obligation'] ?? 0, 2); ?></td>
                                        <td>
                                            <?php
                                            $status = strtolower($trans['status'] ?? 'pending');
                                            $badge_class = 'badge-pending';
                                            if ($status === 'paid') $badge_class = 'badge-paid';
                                            if ($status === 'unpaid') $badge_class = 'badge-unpaid';
                                            ?>
                                            <span class="badge-status <?php echo $badge_class; ?>">
                                                <i class="fas fa-circle me-1" style="font-size: 8px;"></i>
                                                <?php echo ucfirst(htmlspecialchars($trans['status'] ?? 'pending')); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="row mt-4" data-aos="fade-up" data-aos-delay="600">
                <div class="col-12">
                    <div class="quick-actions">
                        <a href="funds/create.php" class="action-btn">
                            <i class="fas fa-plus-circle"></i>
                            Add New Fund
                        </a>
                        <a href="transactions/create.php" class="action-btn" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                            <i class="fas fa-plus-circle"></i>
                            Record Transaction
                        </a>
                        <a href="reports/index.php" class="action-btn" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            <i class="fas fa-file-pdf"></i>
                            Generate Report
                        </a>
                        <a href="export.php" class="action-btn" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                            <i class="fas fa-download"></i>
                            Export Data
                        </a>
                    </div>
                </div>
            </div>

            <!-- Key Insights -->
            <div class="row mt-4" data-aos="zoom-in" data-aos-delay="700">
                <div class="col-12">
                    <div class="insight-card" onclick="showDetailedInsights()">
                        <div class="insight-title">
                            <i class="fas fa-lightbulb"></i>
                            Key Insights
                        </div>
                        <div class="insight-message">
                            <?php
                            $top_component = !empty($component_breakdown) ? $component_breakdown[0]['display_name'] : 'N/A';
                            $top_amount = !empty($component_breakdown) ? $component_breakdown[0]['total_obligated'] : 0;
                            ?>
                            Total allotment of ₱<?php echo number_format($stats['total_allotment']/1000000, 1); ?>M across <?php echo $stats['total_funds']; ?> funds. 
                            <?php echo $top_component; ?> leads with ₱<?php echo number_format($top_amount/1000000, 1); ?>M in obligations. 
                            Current obligation rate is <?php echo $obligation_rate; ?>%.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Include Footer -->
    <?php include 'includes/footer.php'; ?>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

    <script>
        // Initialize AOS (Animate on Scroll)
        AOS.init({
            duration: 1000,
            once: true,
            offset: 50
        });

        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });

        // Initialize DataTable
        $(document).ready(function() {
            $('#recentTransactions').DataTable({
                pageLength: 5,
                order: [[0, 'desc']],
                language: {
                    search: "<i class='fas fa-search'></i>",
                    searchPlaceholder: "Search transactions..."
                },
                dom: '<"top"f>rt<"bottom"lip><"clear">'
            });
        });

        // Chart Colors
        const colors = {
            primary: '#5E60CE',
            secondary: '#6930C3',
            accent1: '#64DFDF',
            accent2: '#FB5607',
            accent3: '#FF006E',
            accent4: '#FFBE0B',
            success: '#28a745',
            danger: '#dc3545',
            warning: '#fd7e14',
            info: '#17a2b8'
        };

        // 1. Component Distribution Chart (Doughnut)
        const componentCtx = document.getElementById('componentChart').getContext('2d');
        new Chart(componentCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($component_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($component_values); ?>,
                    backgroundColor: <?php echo json_encode(array_slice($component_colors, 0, count($component_labels))); ?>,
                    borderWidth: 0,
                    hoverOffset: 15
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 11 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.raw || 0;
                                let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                let percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: ₱${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                },
                cutout: '65%',
                animation: {
                    animateScale: true,
                    animateRotate: true,
                    duration: 2000,
                    easing: 'easeInOutQuart'
                }
            }
        });

        // 2. Yearly Comparison Chart (Bar)
        const yearlyCtx = document.getElementById('yearlyChart').getContext('2d');
        const yearlyChart = new Chart(yearlyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($years); ?>,
                datasets: [
                    {
                        label: 'Allotment',
                        data: <?php echo json_encode($yearly_allotment); ?>,
                        backgroundColor: colors.primary,
                        borderRadius: 8,
                        barPercentage: 0.7,
                        categoryPercentage: 0.8
                    },
                    {
                        label: 'Obligated',
                        data: <?php echo json_encode($yearly_obligated); ?>,
                        backgroundColor: colors.accent1,
                        borderRadius: 8,
                        barPercentage: 0.7,
                        categoryPercentage: 0.8
                    },
                    {
                        label: 'Disbursed',
                        data: <?php echo json_encode($yearly_disbursed); ?>,
                        backgroundColor: colors.success,
                        borderRadius: 8,
                        barPercentage: 0.7,
                        categoryPercentage: 0.8
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 11 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ₱${context.raw.toLocaleString()}`;
                            }
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
                    }
                },
                animation: {
                    duration: 2000,
                    easing: 'easeInOutQuart'
                }
            }
        });

        // 3. Fund Source Distribution Chart (Pie)
        const sourceCtx = document.getElementById('fundSourceChart').getContext('2d');
        new Chart(sourceCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode($source_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($source_values); ?>,
                    backgroundColor: [colors.primary, colors.accent2, colors.accent3, colors.accent4],
                    borderWidth: 0,
                    hoverOffset: 15
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 11 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.raw || 0;
                                let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                let percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: ₱${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                },
                animation: {
                    animateScale: true,
                    animateRotate: true,
                    duration: 2000,
                    easing: 'easeInOutQuart'
                }
            }
        });

        // 4. Component Performance Chart (Horizontal Bar)
        const perfCtx = document.getElementById('performanceChart').getContext('2d');
        const componentNames = <?php echo json_encode(array_slice($component_labels, 0, 5)); ?>;
        const obligatedData = <?php echo json_encode(array_slice($component_values, 0, 5)); ?>;
        
        // Generate random disbursed data for demo (in production, use real data)
        const disbursedData = obligatedData.map(val => val * (Math.random() * 0.5 + 0.3));
        
        new Chart(perfCtx, {
            type: 'bar',
            data: {
                labels: componentNames,
                datasets: [
                    {
                        label: 'Obligated',
                        data: obligatedData,
                        backgroundColor: colors.primary,
                        borderRadius: 8,
                        barPercentage: 0.6
                    },
                    {
                        label: 'Disbursed',
                        data: disbursedData,
                        backgroundColor: colors.accent1,
                        borderRadius: 8,
                        barPercentage: 0.6
                    }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 11 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ₱${context.raw.toLocaleString()}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    }
                },
                animation: {
                    duration: 2000,
                    easing: 'easeInOutQuart'
                }
            }
        });

        // Toggle yearly data
        function toggleYearlyData(type) {
            let data = [];
            switch(type) {
                case 'allotment':
                    data = <?php echo json_encode($yearly_allotment); ?>;
                    break;
                case 'obligated':
                    data = <?php echo json_encode($yearly_obligated); ?>;
                    break;
                case 'disbursed':
                    data = <?php echo json_encode($yearly_disbursed); ?>;
                    break;
            }
            
            // Update chart data
            yearlyChart.data.datasets.forEach((dataset, index) => {
                if (index === 0) {
                    dataset.data = data;
                } else {
                    dataset.data = data.map(() => 0);
                }
            });
            yearlyChart.update();
        }

        // Show detailed insights
        function showDetailedInsights() {
            <?php
            $total_funds = $stats['total_funds'];
            $total_transactions = $stats['total_transactions'];
            $avg_transaction = $stats['total_obligated'] > 0 ? $stats['total_obligated'] / max($total_transactions, 1) : 0;
            ?>
            
            let message = `📊 Detailed Analysis:\n\n`;
            message += `• Total Funds: <?php echo $total_funds; ?>\n`;
            message += `• Total Transactions: <?php echo number_format($total_transactions); ?>\n`;
            message += `• Average Transaction: ₱<?php echo number_format($avg_transaction, 2); ?>\n`;
            message += `• Obligation Rate: <?php echo $obligation_rate; ?>%\n`;
            message += `• Disbursement Rate: <?php echo $disbursement_rate; ?>%\n\n`;
            message += `• Top Component: <?php echo $top_component; ?> (₱<?php echo number_format($top_amount, 2); ?>)\n`;
            
            alert(message);
        }

        // Animate progress bars on load
        window.addEventListener('load', function() {
            const progressBars = document.querySelectorAll('.stat-progress-bar');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 500);
            });
        });
    </script>
</body>
</html>