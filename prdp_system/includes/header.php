<?php
// Get current page for active menu
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Function to check if menu item is active
function isActive($page, $dir = null) {
    global $current_page, $current_dir;
    if ($dir) {
        return ($current_dir == $dir) ? 'active' : '';
    }
    return ($current_page == $page) ? 'active' : '';
}

// Get base URL dynamically
$base_url = '/prdp_system'; // Change this to your actual project folder name

// Get current year for display
$current_year = date('Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PRDP Fund Monitoring System</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- Custom CSS -->
    <link href="<?php echo $base_url; ?>/css/style.css" rel="stylesheet">
    
    <style>
        /* Fallback styles in case external CSS fails */
        .wrapper {
            display: flex;
            width: 100%;
            align-items: stretch;
        }
        #sidebar {
            min-width: 280px;
            max-width: 280px;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav id="sidebar" class="bg-dark">
            <div class="sidebar-header">
                <h3><i class="fas fa-chart-line me-2"></i>PRDP</h3>
                <p class="text-muted mb-0">Fund Monitoring System</p>
            </div>

            <!-- System Info -->
            <div class="system-info">
                <div class="system-icon">
                    <i class="fas fa-building fa-2x"></i>
                </div>
                <div class="system-details">
                    <span class="project-name">Philippine Rural Development Project</span>
                    <span class="fiscal-year">FY <?php echo $current_year; ?></span>
                </div>
            </div>

            <!-- Sidebar Menu -->
            <ul class="list-unstyled components">
                <li class="<?php echo isActive('index.php'); ?>">
                    <a href="<?php echo $base_url; ?>/index.php">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </li>

                <li class="nav-divider">FINANCIAL MANAGEMENT</li>

                <li class="<?php echo isActive('', 'funds') ? 'active' : ''; ?>">
                    <a href="#fundsSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle">
                        <i class="fas fa-coins"></i> Funds Management
                    </a>
                    <ul class="collapse list-unstyled <?php echo (isActive('', 'funds')) ? 'show' : ''; ?>" id="fundsSubmenu">
                        <li><a href="<?php echo $base_url; ?>/funds/index.php"><i class="fas fa-list"></i> All Funds</a></li>
                        <li><a href="<?php echo $base_url; ?>/funds/create.php"><i class="fas fa-plus-circle"></i> Add New Fund</a></li>
                    </ul>
                </li>

                <li class="<?php echo isActive('', 'transactions') ? 'active' : ''; ?>">
                    <a href="#transactionsSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle">
                        <i class="fas fa-exchange-alt"></i> Transactions
                    </a>
                    <ul class="collapse list-unstyled <?php echo (isActive('', 'transactions')) ? 'show' : ''; ?>" id="transactionsSubmenu">
                        <li><a href="<?php echo $base_url; ?>/transactions/index.php"><i class="fas fa-list"></i> All Transactions</a></li>
                        <li><a href="<?php echo $base_url; ?>/transactions/create.php"><i class="fas fa-plus-circle"></i> New Transaction</a></li>
                        <li><a href="<?php echo $base_url; ?>/transactions/disbursements.php"><i class="fas fa-hand-holding-usd"></i> Disbursements</a></li>
                    </ul>
                </li>

                <li class="nav-divider">PROJECT COMPONENTS</li>

                <li>
                    <a href="#iplanSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle">
                        <i class="fas fa-brain"></i> I-PLAN
                    </a>
                    <ul class="collapse list-unstyled" id="iplanSubmenu">
                        <li><a href="<?php echo $base_url; ?>/reports/component.php?code=1.1"><i class="fas fa-chart-pie"></i> Component 1.1</a></li>
                        <li><a href="<?php echo $base_url; ?>/reports/component.php?code=1.2"><i class="fas fa-chart-pie"></i> Component 1.2</a></li>
                    </ul>
                </li>

                <li>
                    <a href="#ibuildSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle">
                        <i class="fas fa-building"></i> I-BUILD
                    </a>
                    <ul class="collapse list-unstyled" id="ibuildSubmenu">
                        <li><a href="<?php echo $base_url; ?>/reports/component.php?code=2.1"><i class="fas fa-chart-pie"></i> Component 2.1</a></li>
                        <li><a href="<?php echo $base_url; ?>/reports/component.php?code=2.2"><i class="fas fa-chart-pie"></i> Component 2.2</a></li>
                    </ul>
                </li>

                <li>
                    <a href="#ireapSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle">
                        <i class="fas fa-seedling"></i> I-REAP
                    </a>
                    <ul class="collapse list-unstyled" id="ireapSubmenu">
                        <li><a href="<?php echo $base_url; ?>/reports/component.php?code=3.1"><i class="fas fa-chart-pie"></i> Component 3.1</a></li>
                        <li><a href="<?php echo $base_url; ?>/reports/component.php?code=3.2"><i class="fas fa-chart-pie"></i> Component 3.2</a></li>
                    </ul>
                </li>

                <li>
                    <a href="<?php echo $base_url; ?>/reports/component.php?code=4.0">
                        <i class="fas fa-headset"></i> I-SUPPORT
                    </a>
                </li>

                <li>
                    <a href="<?php echo $base_url; ?>/reports/component.php?code=SRE">
                        <i class="fas fa-star"></i> SRE
                    </a>
                </li>

                <li class="nav-divider">REPORTS & ANALYTICS</li>

                <li class="<?php echo isActive('', 'reports') ? 'active' : ''; ?>">
                    <a href="#reportsSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle">
                        <i class="fas fa-file-alt"></i> Reports
                    </a>
                    <ul class="collapse list-unstyled <?php echo (isActive('', 'reports')) ? 'show' : ''; ?>" id="reportsSubmenu">
                        <li><a href="<?php echo $base_url; ?>/reports/index.php"><i class="fas fa-chart-bar"></i> Summary Report</a></li>
                        <li><a href="<?php echo $base_url; ?>/reports/quarterly.php"><i class="fas fa-calendar-alt"></i> Quarterly Report</a></li>
                        <li><a href="<?php echo $base_url; ?>/reports/disbursement.php"><i class="fas fa-hand-holding-usd"></i> Disbursement Report</a></li>
                        <li><a href="<?php echo $base_url; ?>/reports/obligation.php"><i class="fas fa-file-invoice"></i> Obligation Report</a></li>
                    </ul>
                </li>

                <li class="nav-divider">SYSTEM TOOLS</li>

                <li>
                    <a href="<?php echo $base_url; ?>/backup.php">
                        <i class="fas fa-database"></i> Backup Database
                    </a>
                </li>

                <li>
                    <a href="<?php echo $base_url; ?>/import.php">
                        <i class="fas fa-file-import"></i> Import Data
                    </a>
                </li>

                <li>
                    <a href="<?php echo $base_url; ?>/export.php">
                        <i class="fas fa-file-export"></i> Export Data
                    </a>
                </li>
            </ul>

            <!-- Sidebar Footer -->
            <div class="sidebar-footer">
                <small>© <?php echo date('Y'); ?> PRDP</small>
                <small>Version 2.0 | All rights reserved</small>
            </div>
        </nav>

        <!-- Page Content -->
        <div id="content">
            <!-- Top Navbar -->
            <nav class="navbar navbar-expand-lg navbar-light bg-light">
                <div class="container-fluid">
                    <button type="button" id="sidebarCollapse" class="btn btn-dark">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <div class="ms-auto d-flex align-items-center">
                        <!-- Quick Actions Dropdown -->
                        <div class="dropdown me-3">
                            <button class="btn btn-outline-primary dropdown-toggle" type="button" id="quickActions" data-bs-toggle="dropdown">
                                <i class="fas fa-plus-circle"></i> Quick Actions
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="<?php echo $base_url; ?>/funds/create.php"><i class="fas fa-coins"></i> New Fund</a></li>
                                <li><a class="dropdown-item" href="<?php echo $base_url; ?>/allotments/create.php"><i class="fas fa-file-invoice"></i> New Allotment</a></li>
                                <li><a class="dropdown-item" href="<?php echo $base_url; ?>/transactions/create.php"><i class="fas fa-exchange-alt"></i> New Transaction</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?php echo $base_url; ?>/reports/index.php"><i class="fas fa-file-pdf"></i> Generate Report</a></li>
                                <li><a class="dropdown-item" href="<?php echo $base_url; ?>/export.php"><i class="fas fa-file-export"></i> Export Data</a></li>
                            </ul>
                        </div>

                        <!-- Notifications -->
                        <div class="dropdown me-3">
                            <button class="btn btn-outline-warning position-relative" type="button" id="notifications" data-bs-toggle="dropdown">
                                <i class="fas fa-bell"></i>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                    3
                                </span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end" style="width: 300px;">
                                <h6 class="dropdown-header">Notifications</h6>
                                <div class="dropdown-item-text">
                                    <small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Low balance alert: I-PLAN 1.1</small>
                                </div>
                                <div class="dropdown-item-text">
                                    <small class="text-info"><i class="fas fa-info-circle"></i> New allotment created</small>
                                </div>
                                <div class="dropdown-item-text">
                                    <small class="text-success"><i class="fas fa-check-circle"></i> Transaction completed</small>
                                </div>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-center" href="#">View All</a>
                            </div>
                        </div>

                        <!-- Current Date/Time -->
                        <div class="current-datetime text-muted">
                            <i class="fas fa-calendar-alt me-1"></i>
                            <?php echo date('F j, Y'); ?>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Main Content Area -->
            <div class="main-content">